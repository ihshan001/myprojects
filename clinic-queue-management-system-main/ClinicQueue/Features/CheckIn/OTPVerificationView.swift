//
//  OTPVerificationView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-05.
//

import SwiftUI

struct OTPVerificationView: View {
    let phoneNumber: String
    @Environment(\.presentationMode) var presentationMode
    
    @EnvironmentObject var sessionManager: SessionManager
    @State private var otpDigits: [String] = Array(repeating: "", count: 4)
    @FocusState private var focusedField: Int?
    @State private var navigateToHome = false
    
    var body: some View {
        VStack(alignment: .leading, spacing: 24) {
            Button(action: {
                presentationMode.wrappedValue.dismiss()
            }) {
                HStack {
                    Image(systemName: "chevron.left")
                        .font(.system(size: 20, weight: .medium))
                 
                }
                .foregroundColor(.black)
            }
            .padding(.top, 20)
            
       
            VStack(alignment: .leading, spacing: 8) {
                Text("Enter Verification Code ")
                    .font(.system(size: 28, weight: .bold))
                
                Text("We've sent a 4-digit code to your phone number  ")
                    .font(.system(size: 14))
                    .foregroundColor(.gray)
            }
            .padding(.top, 20)
        
            Text("This code  exprie in 5 minutes.")
                .font(.system(size: 14))
                .foregroundColor(.gray)
                .frame(maxWidth: .infinity, alignment: .center)
                .padding(0)
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
            .padding(.top, 0)
            
        
            PrimaryButton(
                title: "Verify",
                backgroundColor: isOTPComplete ? AppColors.primary : AppColors.primary.opacity(0.5)
            ) {
                if isOTPComplete {
                    sessionManager.startPhoneSession(phoneNumber: phoneNumber)
                    navigateToHome = true
                }
            }
            .disabled(!isOTPComplete)
            .padding(.top, 30)
            
 
            HStack {
                Text("Didn't receive code?")
                    .font(.system(size: 14))
                    .foregroundColor(.gray)
                
                Button(action: {
         
                    clearOTPFields()
                }) {
                    Text("Resend")
                        .font(.system(size: 14, weight: .semibold))
                        .foregroundColor(AppColors.primary)
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.top, 20)
            
            Spacer()
        }
        .padding(.horizontal, 24)
        .navigationBarHidden(true)
        .onAppear {
            DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
                focusedField = 0
            }
        }
        .background(
            NavigationLink(destination: ServicesView(), isActive: $navigateToHome) {
                
                EmptyView()
                    .navigationBarBackButtonHidden(true)
            }
        )
    }
    

    private var isOTPComplete: Bool {
        !otpDigits.contains { $0.isEmpty }
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
    
  
    private func clearOTPFields() {
        for i in 0..<4 {
            otpDigits[i] = ""
        }
        focusedField = 0
    }
}

struct OTPDigitBox: View {
    @Binding var digit: String
    var isFocused: Bool = false
    
    var body: some View {
        TextField("", text: $digit)
            .font(.system(size: 24, weight: .bold))
            .multilineTextAlignment(.center)
            .frame(width: 60, height: 60)
            .background(
                RoundedRectangle(cornerRadius: 12)
                    .fill(Color(.systemGray6))
            )
            .overlay(
                RoundedRectangle(cornerRadius: 12)
                    .stroke(isFocused ? AppColors.primary : Color.gray.opacity(0.3),
                            lineWidth: isFocused ? 2 : 1)
            )
            .keyboardType(.numberPad)
            .textContentType(.oneTimeCode)
    }
}


struct OTPVerificationView_Previews: PreviewProvider {
    static var previews: some View {
        OTPVerificationView(phoneNumber: "77 123 4567")
    }
}
