// PhoneNumberEntryView.swift
import SwiftUI

struct PhoneNumberEntryView: View {
    @Environment(\.presentationMode) var presentationMode
    @EnvironmentObject var sessionManager: SessionManager
    @State private var phoneNumber = ""
    @State private var navigateToOTP = false
    
    var body: some View {
        VStack(alignment: .leading, spacing: 24) {
            // Back Button
            Button(action: {
                presentationMode.wrappedValue.dismiss()
            }) {
                HStack {
                    Image(systemName: "chevron.left")
                        .font(.system(size: 20, weight: .medium))
                    Text("Back")
                        .font(.system(size: 16))
                }
                .foregroundColor(AppColors.primary)
            }
            .padding(.top, 20)
            
            // Header
            VStack(alignment: .leading, spacing: 8) {
                Text("Enter Phone Number")
                    .font(.system(size: 28, weight: .bold))
                
                Text("We'll send you a verification code")
                    .font(.system(size: 14))
                    .foregroundColor(.gray)
            }
            .padding(.top, 20)
            
            // Phone Number Input
            VStack(alignment: .leading, spacing: 8) {
                Text("Phone Number")
                    .font(.system(size: 14, weight: .medium))
                    .foregroundColor(.gray)
                
                HStack {
                    // Country Code
                    Text("+94")
                        .font(.system(size: 16, weight: .medium))
                        .padding(.leading, 12)
                    
                    TextField("", text: $phoneNumber)
                        .font(.system(size: 16))
                        .keyboardType(.numberPad)
                        .placeholder(when: phoneNumber.isEmpty) {
                            Text("77 123 4567")
                                .foregroundColor(.gray.opacity(0.5))
                        }
                }
                .padding(.vertical, 12)
                .background(
                    RoundedRectangle(cornerRadius: 12)
                        .stroke(phoneNumber.isEmpty ? Color.gray.opacity(0.3) : AppColors.primary, lineWidth: 1)
                )
            }
            
            // Continue Button
            PrimaryButton(
                title: "Continue",
                backgroundColor: phoneNumber.count >= 9 ? AppColors.primary : AppColors.primary.opacity(0.5)
            ) {
                if phoneNumber.count >= 9 {
                    sessionManager.startPhoneSession(phoneNumber: phoneNumber)
                    navigateToOTP = true
                }
            }
            .disabled(phoneNumber.count < 9)
            .padding(.top, 20)
            
            // Terms and Conditions
            VStack(spacing: 4) {
                Text("By continuing, you agree to our")
                    .font(.system(size: 12))
                    .foregroundColor(.gray)
                
                HStack(spacing: 4) {
                    Text("Terms of Service")
                        .font(.system(size: 12, weight: .medium))
                        .foregroundColor(AppColors.primary)
                    
                    Text("and")
                        .font(.system(size: 12))
                        .foregroundColor(.gray)
                    
                    Text("Privacy Policy")
                        .font(.system(size: 12, weight: .medium))
                        .foregroundColor(AppColors.primary)
                }
            }
            .frame(maxWidth: .infinity)
            .padding(.top, 30)
            
            Spacer()
        }
        .padding(.horizontal, 24)
        .navigationBarHidden(true)
        .background(
            NavigationLink(destination: OTPVerificationView(phoneNumber: phoneNumber), isActive: $navigateToOTP) {
                EmptyView()
            }
        )
    }
}

// Helper extension for placeholder
extension View {
    func placeholder<Content: View>(
        when shouldShow: Bool,
        alignment: Alignment = .leading,
        @ViewBuilder placeholder: () -> Content) -> some View {
        
        ZStack(alignment: alignment) {
            placeholder().opacity(shouldShow ? 1 : 0)
            self
        }
    }
}
// Preview
struct PhoneNumberEntryView_Previews: PreviewProvider {
    static var previews: some View {
        PhoneNumberEntryView()
    }
}
