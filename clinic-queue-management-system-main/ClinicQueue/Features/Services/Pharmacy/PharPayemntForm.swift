//
//  PharPayemntForm.swift
//  ClinicQueue
//
//  Created by Roshan on 2026-03-12.
//

import SwiftUI

struct PharPayemntForm: View {
    
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
    
    var backgroundColor: Color = AppColors.primary
    
    @EnvironmentObject var sessionManager: SessionManager
    @State private var selectedPaymentOption: String? = "card"
    @State private var navigateToPaymentView = false

    private var isOtpComplete: Bool {
        otpDigits.allSatisfy { $0.count == 1 }
    }

    private var paymentDetailsData: [PaymentDetailRow] {
        [
            PaymentDetailRow(label: "Imaging Tests", value: "$ 50.00"),
            PaymentDetailRow(label: "Admin Fee", value: "$ 1.00"),
            PaymentDetailRow(label: "Additional Discount", value: "$ \(String(format: "00.00", PaymentConfig.additionalDiscount))"),
            PaymentDetailRow(label: "Total", value: "$ 51.000")
        ]
    }

    private let paymentMethods = [
        PaymentMethod(name: "Apple Pay", iconName: "apple"),
        PaymentMethod(name: "Visa", iconName: "visa"),
        PaymentMethod(name: "MasterCard", iconName: "mastercard"),
        PaymentMethod(name: "PayPal", iconName: "paypal")
    ]

    var body: some View {
        
        NavigationStack {
            
            ZStack(alignment: .bottom) {
                
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
                        
                        Spacer(minLength: 120)
                    }
                }
                
                
                VStack(spacing: 0) {
                    
                    LinearGradient(
                        colors: [
                            Color(.systemBackground).opacity(0),
                            Color(.systemBackground)
                        ],
                        startPoint: .top,
                        endPoint: .bottom
                    )
                    .frame(height: 30)
                    
                    HStack {
                        PrimaryButton(title: "Next", maxWidth: 300) {
                            resetOtpTimer()
                            isOtpModalOpen = true
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 16)
                    .background(Color(.systemBackground))
                }
                
                
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
                        .frame(maxWidth: .infinity)
                        
                        
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
                            backgroundColor: isOtpComplete
                            ? AppColors.primary
                            : AppColors.primary.opacity(0.5)
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
            
            
            .navigationDestination(isPresented: $isNavigateToPaymentProcess) {
                PaymentProces()
                    .environmentObject(sessionManager)
            }
        }
    }

    
    private func handleOTPInput(at index: Int, newValue: String) {
        
        if newValue.count > 1 {
            otpDigits[index] = String(newValue.last ?? Character(""))
        }
        
        if !newValue.isEmpty && index < 3 {
            focusedField = index + 1
        } else if newValue.isEmpty && index > 0 {
            focusedField = index - 1
        }
    }

    
    private func formatTime() -> String {
        
        let minutes = remainingSeconds / 60
        let seconds = remainingSeconds % 60
        
        return String(format: "%02d:%02d", minutes, seconds)
    }

    
    private func resetOtpTimer() {
        
        timer?.invalidate()
        
        remainingSeconds = 349
        
        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { _ in
            
            if remainingSeconds > 0 {
                remainingSeconds -= 1
            } else {
                timer?.invalidate()
            }
        }
    }
}

#Preview {
    PharPayemntForm()
        .environmentObject(SessionManager())
}
