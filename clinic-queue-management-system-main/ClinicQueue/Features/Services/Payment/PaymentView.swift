//
//  PaymentView.swift
//  ClinicQueue
//
//  Created by dilshan fernando on 2026-03-08.
//

import SwiftUI

struct PaymentView<SuccessDestination: View>: View {
    
  
    
    let onPaymentSuccess: () -> SuccessDestination
    
    @State private var nameOnCard = ""
    @State private var cardNumber = ""
    @State private var cvv = ""
    @State private var expirationDate = ""
    
    @State private var isOtpModalOpen = false
    @State private var isNavigateToPaymentProcess = false
    
    @State private var otpDigits: [String] = Array(repeating: "", count: 4)
    @FocusState private var focusedField: Int?
    
    @State private var remainingSeconds = 349
    @State private var timer: Timer?
    
    @Binding var currentVisit: ClinicVisit

    
    private var paymentDetailsData: [PaymentDetailRow] {
        [
            PaymentDetailRow(label: "Consultation", value: "$\(String(format: "%.2f", currentVisit.consultationFee ?? 0.0))"),
            PaymentDetailRow(label: "Admin Fee", value: "$\(String(format: "%.2f", currentVisit.adminFee ?? 0.0))"),
            PaymentDetailRow(label: "Additional Discount", value: "$\(String(format: "%.2f", PaymentConfig.additionalDiscount))"),
            PaymentDetailRow(label: "Total", value: "$\(String(format: "%.2f", currentVisit.totalPayment))")
        ]
    }
    
    private let paymentMethods = [
        PaymentMethod(name: "Apple Pay", iconName: "apple"),
        PaymentMethod(name: "Visa", iconName: "visa"),
        PaymentMethod(name: "MasterCard", iconName: "mastercard"),
        PaymentMethod(name: "PayPal", iconName: "paypal")
    ]
    
    private var isCardInfoValid: Bool {
        let containsNumbers = nameOnCard.rangeOfCharacter(from: .decimalDigits) != nil
        let isNameValid = !nameOnCard.trimmingCharacters(in: .whitespaces).isEmpty && !containsNumbers
        
        let isCardNumberValid = cardNumber.count >= 15
        let isCvvValid = cvv.count >= 3
        
        let isExpValid = validateExpirationDate(expirationDate)
        
        return isNameValid && isCardNumberValid && isCvvValid && isExpValid
    }

    private func validateExpirationDate(_ dateStr: String) -> Bool {
        let components = dateStr.split(separator: "/")
        guard components.count == 2,
              let yearSuffix = Int(components[0]),
              let month = Int(components[1]),
              (1...12).contains(month) else {
            return false
        }
        
        let fullYear = 2000 + yearSuffix
        
        let calendar = Calendar.current
        let currentYear = calendar.component(.year, from: Date())
        let currentMonth = calendar.component(.month, from: Date())
        
        if fullYear > currentYear {
            return true
        } else if fullYear == currentYear {
            return month >= currentMonth
        }
        
        return false
    }
    
    private var isOtpComplete: Bool {
        !otpDigits.contains { $0.isEmpty }
    }
    
    var body: some View {
        
        NavigationStack {
            ZStack {
                ScrollView {
                    VStack(alignment: .leading, spacing: 20) {
                        
                        Text("Payment")
                            .font(.system(size: 20, weight: .bold))
                            .frame(maxWidth: .infinity, alignment: .center)
                            .padding(.horizontal)
                        
                        PaymentDetails(rows: paymentDetailsData)
                            .padding(.horizontal)
                            .padding(.top, Spacing.section)
                        
                        PaymentMethodSelector(
                            title: "Choose Payment Method",
                            methods: paymentMethods
                        )
                        .padding(.horizontal)
                        .padding(.top, Spacing.section)
                        
                        CardInfoForm(
                            nameOnCard: $nameOnCard,
                            cardNumber: $cardNumber,
                            cvv: $cvv,
                            expirationDate: $expirationDate
                        )
                        .padding(.top, Spacing.section)
                        .padding(.bottom, 12)
                        
                    }
                } .padding(.bottom, 90)
                
                
                if isOtpModalOpen {
                    
                    CustomModal(isPresented: $isOtpModalOpen) {
                        
                        VStack(spacing: 16) {
                            
                            Text("Please enter the OTP code you received on your (079 878 8779) phone.")
                                .font(.subheadline)
                                .bold()
                                .multilineTextAlignment(.center)
                            
                            
                            HStack(spacing: 6) {
                                ForEach(0..<4, id: \.self) { index in
                                    OTPDigitBox(
                                        digit: $otpDigits[index],
                                        isFocused: focusedField == index
                                    )
                                    .focused($focusedField, equals: index)
                                    .onChange(of: otpDigits[index]) { newValue in
                                        handleOTPInput(at: index, newValue: newValue)
                                    }
                                }
                            }
                            .frame(maxWidth: .infinity, alignment: .center)
                            
                            
                            Group {
                                if remainingSeconds > 0 {
                                    
                                    Text("The OTP code will expire in ")
                                        .foregroundColor(AppColors.lableColor)
                                    +
                                    Text(formatTime())
                                        .foregroundColor(AppColors.dark)
                                    
                                } else {
                                    
                                    Text("OTP expired")
                                        .foregroundColor(AppColors.error)
                                }
                            }
                            .font(.system(size: 15))
                            
                            
                            PrimaryButton(
                                title: "Confirm",
                                backgroundColor: isOtpComplete ? AppColors.primary : AppColors.primary.opacity(0.5)
                            ) {
                                timer?.invalidate()
                                isOtpModalOpen = false
                                
                                DispatchQueue.main.asyncAfter(deadline: .now() + 0.3) {
                                    isNavigateToPaymentProcess = true
                                }
                            }
                            .disabled(!isOtpComplete)
                        
                            
                            
                            LinkButton(
                                title: "Resend",
                                textColor: AppColors.placeholder
                            ) {
                                otpDigits = Array(repeating: "", count: 4)
                                resetOtpTimer()
                            }
                        }
                        .padding()
                    }
                }
                VStack {
                    Spacer()

                    VStack {
                        PrimaryButton(
                            title: "Book Appointment",
                            backgroundColor: isCardInfoValid ? AppColors.primary : AppColors.primary.opacity(0.5)
                        ) {
                            isOtpModalOpen = true
                            resetOtpTimer()
                        }
                        .disabled(!isCardInfoValid)
                    }
                    .padding(20)
                    .background(Color(UIColor.systemBackground))
                    .shadow(color: Color.black.opacity(0.05), radius: 5, x: 0, y: -5)
                }
                .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .bottom)
                
                
                FloatingNav(
                    mainIcon: "plus",
                    items: [
                        FloatingNavItem(icon: "house.fill", label: "Home", destination: AnyView(ServicesView())),
                        FloatingNavItem(icon: "map.fill", label: "Map", destination: AnyView(Text("Map View"))),
                        FloatingNavItem(icon: "gearshape.fill", label: "Settings", destination: AnyView(SettingsView()))
                    ]
                ).padding(.top, 280)
            }
            
            
            
            .navigationDestination(isPresented: $isNavigateToPaymentProcess) {
                PaymentProcessingView(destination: onPaymentSuccess())
            }
        }
    }
    
    
    private func handleOTPInput(at index: Int, newValue: String) {
        
        if newValue.count > 1 {
            otpDigits[index] = String(newValue.prefix(1))
        }
        
        if !newValue.isEmpty && index < 3 {
            focusedField = index + 1
        }
        
        if index == 3 && !newValue.isEmpty {
            focusedField = nil
        }
    }
    
    

    private func startOtpTimer() {
        
        timer?.invalidate()
        
        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { _ in
            
            if remainingSeconds > 0 {
                remainingSeconds -= 1
            } else {
                timer?.invalidate()
            }
        }
    }
    
    
    private func resetOtpTimer() {
        remainingSeconds = 349
        startOtpTimer()
    }
    
    
    private func formatTime() -> String {
        
        let minutes = remainingSeconds / 60
        let seconds = remainingSeconds % 60
        
        return String(format: "%d minutes and %02d seconds", minutes, seconds)
    }
}

//#Preview {
//    PaymentView {
//        PaymentStatusView(
//            isSuccess: true,
//            onContinue: { Text("Next Page") }
//        )
//    }
//}
